<?php

/**
 * 휴면계정 정리 모듈
 * 
 * Copyright (c) 2015, Kijin Sung <kijin@kijinsung.com>
 * 
 * 이 프로그램은 자유 소프트웨어입니다. 소프트웨어의 피양도자는 자유 소프트웨어
 * 재단이 공표한 GNU 일반 공중 사용 허가서 2판 또는 그 이후 판을 임의로
 * 선택해서, 그 규정에 따라 프로그램을 개작하거나 재배포할 수 있습니다.
 *
 * 이 프로그램은 유용하게 사용될 수 있으리라는 희망에서 배포되고 있지만,
 * 특정한 목적에 맞는 적합성 여부나 판매용으로 사용할 수 있으리라는
 * 묵시적인 보증을 포함한 어떠한 형태의 보증도 제공하지 않습니다.
 * 보다 자세한 사항에 대해서는 GNU 일반 공중 사용 허가서를 참고하시기 바랍니다.
 *
 * GNU 일반 공중 사용 허가서는 이 프로그램과 함께 제공됩니다.
 * 만약, 이 문서가 누락되어 있다면 자유 소프트웨어 재단으로 문의하시기 바랍니다.
 */
class Member_ExpireController extends Member_Expire
{
	/**
	 * 임시 복원된 회원정보를 기억하는 변수.
	 */
	protected static $_temp_member = array();
	
	/**
	 * 임시 복원 처리가 필요한 act 목록.
	 */
	protected static $_acts_to_intercept = array(
		'procMemberLogin',
		'procMemberFindAccount',
		'procMemberFindAccountByQuestion',
		'procMemberResendAuthMail',
		'procMemberAuthAccount',
	);
	
	/**
	 * 회원 추가 및 수정 전 트리거.
	 * 별도의 저장공간으로 이동된 회원과 같은 아이디 등을 사용하여 가입하거나
	 * 중복되는 내용으로 회원정보를 수정하는 것을 금지한다.
	 */
	public function triggerBlockDuplicates($args)
	{
		// 별도 저장된 휴면회원과 같은 아이디로 가입하는 것을 금지한다.
		if ($args->user_id)
		{
			$obj = new stdClass();
			$obj->user_id = $args->user_id;
			$output = executeQuery('member_expire.getMovedMembers', $obj);
			if ($output->toBool() && count($output->data))
			{
				return new Object(-1,'msg_exists_user_id');
			}
		}
		
		// 별도 저장된 휴면회원과 같은 메일 주소로 가입하는 것을 금지한다.
		if ($args->email_address)
		{
			$obj = new stdClass();
			$obj->email_address = $args->email_address;
			$output = executeQuery('member_expire.getMovedMembers', $obj);
			if ($output->toBool() && count($output->data))
			{
				$config = $this->getConfig();
				if ($config->auto_restore === 'Y')
				{
					return new Object(-1, 'msg_exists_expired_email_address_auto_restore');
				}
				else
				{
					return new Object(-1,'msg_exists_expired_email_address');
				}
			}
		}
		
		// 별도 저장된 휴면회원과 같은 닉네임으로 가입하는 것을 금지한다.
		if ($args->nick_name)
		{
			$obj = new stdClass();
			$obj->nick_name = $args->nick_name;
			$output = executeQuery('member_expire.getMovedMembers', $obj);
			if ($output->toBool() && count($output->data))
			{
				return new Object(-1,'msg_exists_nick_name');
			}
		}
	}
	
	/**
	 * 회원 로그아웃 트리거.
	 * 로그아웃과는 무관하고, 적당한 간격으로 자동 정리를 실행하는 데 쓰인다.
	 * 로그아웃 트리거를 사용하는 이유는 그나마 다른 작업에 영향을 적게 미치면서
	 * 호출 빈도가 실제 회원수에 비례할 가능성이 높기 때문이다.
	 */
	public function triggerAutoExpire()
	{
		// 자동 정리 옵션을 사용하지 않는다면 종료한다.
		$config = $this->getConfig();
		if ($config->auto_expire !== 'Y')
		{
			return;
		}
		
		// 정리할 휴면계정이 있는지 확인한다.
		$obj = new stdClass();
		$obj->is_admin = 'N';
		$obj->threshold = date('YmdHis', time() - ($config->expire_threshold * 86400) + zgap());
		$obj->list_count = $obj->page_count = $obj->page = 1;
		$obj->orderby = 'asc';
		$members_query = executeQuery('member_expire.getExpiredMembers', $obj);
		
		// 정리할 휴면계정이 있다면 지금 정리한다.
		if ($members_query->toBool() && count($members_query->data))
		{
			$oDB = DB::getInstance();
			$oDB->begin();
			$oModel = getModel('member_expire');
			
			foreach ($members_query->data as $member)
			{
				if ($config->expire_method === 'delete')
				{
					$oModel->deleteMember($member, true, false);
				}
				else
				{
					$oModel->moveMember($member, false);
				}
			}
			
			$oDB->commit();
		}
	}
	
	/**
	 * 모듈 실행 전 트리거.
	 * 로그인, 아이디/비번찾기 등 휴면계정을 다시 활성화시키기 위해 꼭 필요한 작업을 할 때
	 * 코어에서 회원정보에 접근할 수 있도록 임시로 member 테이블에 레코드를 옮겨 준다.
	 * 필요없게 되면 모듈 실행 후 트리거에서 원위치시킨다.
	 */
	public function triggerBeforeModuleProc($oModule)
	{
		// 처리가 필요하지 않은 act인 경우 즉시 실행을 종료한다.
		if (!in_array($oModule->act, self::$_acts_to_intercept)) return;
		
		// 로그인 및 인증을 위해 입력된 아이디, 메일 주소 또는 member_srl을 파악한다.
		$user_id = Context::get('user_id');
		$email_address = Context::get('email_address');
		$member_srl = (!$user_id && !$email_address && Context::get('auth_key')) ? Context::get('member_srl') : null;
		if (strpos($user_id, '@') !== false)
		{
			$email_address = $user_id;
			$user_id = null;
		}
		if (!$user_id && !$email_address && !$member_srl)
		{
			return;
		}
		
		// 주어진 정보와 일치하는 회원이 있는지 확인한다.
		$obj = new stdClass();
		if ($user_id)
		{
			$obj->user_id = $user_id;
			$output = executeQuery('member.getMemberSrl', $obj);
		}
		elseif ($email_address)
		{
			$obj->email_address = $email_address;
			$output = executeQuery('member.getMemberSrl', $obj);
		}
		else
		{
			$obj->member_srl = $member_srl;
			$output = executeQuery('member.getMemberInfoByMemberSrl', $obj);
		}
		if ($output->toBool() && count($output->data))
		{
			return;
		}
		
		// 별도의 저장공간으로 이동된 휴면회원 중 주어진 정보와 일치하는 경우가 있는지 확인한다.
		$output = executeQuery('member_expire.getMovedMembers', $obj);
		if (!$output->toBool() || !count($output->data))
		{
			return;
		}
		
		// 자동 복원 기능을 사용하지 않는 경우, 휴면 처리되었다는 메시지를 출력한다.
		$config = $this->getConfig();
		if ($config->auto_restore !== 'Y')
		{
			return new Object(-1, 'msg_your_membership_has_expired');
		}
		
		// 회원정보를 member 테이블로 복사한다.
		$member = reset($output->data);
		$output = getModel('member_expire')->restoreMember($member, true);
		if (!$output)
		{
			return;
		}
		
		// 임시로 복원해 놓았음을 표시하여, 인증 실패시 되돌릴 수 있도록 한다.
		self::$_temp_member = $member;
		return;			
	}
	
	/**
	 * 모듈 실행 후 트리거.
	 * 임시로 member 테이블에 옮겨놓은 레코드를 원위치시킨다.
	 */
	public function triggerAfterModuleProc($oModule)
	{
		// 실행 전 트리거에서 임시로 복원해 둔 회원이 없다면 여기서도 할 일이 없다.
		if (!self::$_temp_member) return;
		
		// 로그인에 성공했다면 원래대로 돌려놓을 필요가 없다.
		if ($_SESSION['member_srl']) return;
		
		// 그 밖의 경우, 회원정보를 원위치시킨다.
		getModel('member_expire')->moveMember(self::$_temp_member, true);
	}
}